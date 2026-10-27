<script setup>
import { useFormErrors } from '@/lib/formErrors';
import { ref, computed, onMounted } from 'vue';
import { Plus, Pencil } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Badge from '@/components/ui/Badge.vue';

const rows = ref([]);
const loading = ref(false);
const modal = ref({ open: false, row: null });
const form = ref({});
const errors = useFormErrors(() => form.value);
const saving = ref(false);

const columns = [
    { key: 'name', label: 'Leave Type', sortable: true },
    { key: 'code', label: 'Code' },
    { key: 'days_per_year', label: 'Days / Year', align: 'right' },
    { key: 'monthly_accrual', label: 'Monthly Accrual', align: 'right' },
    { key: 'counting', label: 'Counting', format: (v) => ({ working: 'Working days', calendar: 'Calendar days' })[v] ?? v },
    { key: 'paid', label: 'Pay', format: (v) => ({ full: 'Full pay', half: 'Half pay', unpaid: 'Unpaid' })[v] ?? v },
    { key: 'active', label: 'Status' },
];

const blank = () => ({
    name: '', code: '', days_per_year: null, opening_days: null, counting: 'working', paid: 'full',
    accrues_monthly: false, monthly_accrual: null, carry_forward: false, carry_forward_cap: null,
    has_balance: true, requires_document: false, allow_half_day: false, allow_hours: false,
    gender: null, max_consecutive: null, color: '#6366f1', sort_order: 0, active: true, balance_type_id: null,
});

// What the accrual job will credit when the field is left empty (mirrors LeaveType::monthlyAccrual).
const accrualHint = computed(() => {
    const d = Number(form.value.days_per_year);
    return d > 0 ? `Empty = company setting (default 1.75), else ${(d / 12).toFixed(2)} (days per year ÷ 12)` : 'Empty = company setting (default 1.75)';
});

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/leave/types');
        rows.value = data.data ?? data;
    } finally {
        loading.value = false;
    }
}

function edit(row) {
    const f = blank();
    for (const k of Object.keys(f)) if (row && row[k] !== undefined) f[k] = row[k];
    form.value = f;
    errors.value = {};
    modal.value = { open: true, row };
}

const num = (v) => (v === '' || v === null || v === undefined ? null : Number(v));

async function save() {
    saving.value = true;
    errors.value = {};
    const payload = {
        ...form.value,
        days_per_year: num(form.value.days_per_year),
        opening_days: num(form.value.opening_days),
        monthly_accrual: num(form.value.monthly_accrual),
        carry_forward_cap: num(form.value.carry_forward_cap),
        max_consecutive: num(form.value.max_consecutive),
        sort_order: Number(form.value.sort_order) || 0,
        gender: form.value.gender || null,
    };
    try {
        if (modal.value.row) await api.put(`/leave/types/${modal.value.row.id}`, payload);
        else await api.post('/leave/types', payload);
        swal.toast('Leave type saved.');
        modal.value.open = false;
        await load();
    } catch (e) {
        errors.value = fieldErrors(e);
        swal.toast(errorMessage(e), 'error');
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Leave Types" subtitle="Entitlements, accrual and rules per leave type">
            <template #actions>
                <button class="btn-primary" @click="edit(null)"><Plus class="size-4" /> Add Leave Type</button>
            </template>
        </PageHeader>

        <DataTable :columns="columns" :rows="rows" :loading="loading">
            <template #cell-days_per_year="{ row }">
                {{ row.effective_days_per_year ?? row.days_per_year ?? '—' }}
                <span v-if="row.follows_company_settings" class="block text-[11px] text-slate-400">company setting</span>
            </template>
            <template #cell-monthly_accrual="{ row }">
                <template v-if="row.accrues_monthly">
                    {{ row.effective_monthly_accrual ?? row.monthly_accrual ?? 'Company default' }}
                    <span v-if="row.follows_company_settings" class="block text-[11px] text-slate-400">company setting</span>
                </template>
                <span v-else class="text-slate-400">—</span>
            </template>
            <template #cell-active="{ row }">
                <Badge :color="row.active ? 'green' : 'slate'">{{ row.active ? 'Active' : 'Inactive' }}</Badge>
            </template>
            <template #actions="{ row }">
                <button class="btn-ghost !p-1.5" @click="edit(row)"><Pencil class="size-4" /></button>
            </template>
        </DataTable>

        <Modal :open="modal.open" :title="modal.row ? `Edit ${modal.row.name}` : 'Add Leave Type'" size="lg" @close="modal.open = false">
            <div class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label">Name</label>
                        <input v-model="form.name" type="text" class="input" />
                        <p v-if="errors.name" class="field-error">{{ errors.name }}</p>
                    </div>
                    <div>
                        <label class="label">Code</label>
                        <input v-model="form.code" type="text" class="input" :disabled="!!modal.row" />
                        <p v-if="errors.code" class="field-error">{{ errors.code }}</p>
                    </div>
                    <div>
                        <label class="label">Days per year</label>
                        <input v-model="form.days_per_year" type="number" min="0" step="0.25" class="input" />
                        <p v-if="errors.days_per_year" class="field-error">{{ errors.days_per_year }}</p>
                    </div>
                    <div>
                        <label class="label">Opening days (new hires)</label>
                        <input v-model="form.opening_days" type="number" min="0" step="0.25" class="input" />
                    </div>
                    <div>
                        <label class="label">Counting</label>
                        <select v-model="form.counting" class="input"><option value="working">Working days</option><option value="calendar">Calendar days</option></select>
                    </div>
                    <div>
                        <label class="label">Pay</label>
                        <select v-model="form.paid" class="input"><option value="full">Full pay</option><option value="half">Half pay</option><option value="unpaid">Unpaid</option></select>
                    </div>
                </div>

                <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                    <label class="flex items-center gap-2 text-sm"><input v-model="form.accrues_monthly" type="checkbox" /> Accrues monthly</label>
                    <div v-if="form.accrues_monthly" class="mt-3">
                        <label class="label">Monthly accrual (days)</label>
                        <input v-model="form.monthly_accrual" type="number" min="0" step="0.01" class="input sm:w-48" placeholder="1.75" />
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ accrualHint }}</p>
                        <p v-if="errors.monthly_accrual" class="field-error">{{ errors.monthly_accrual }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.carry_forward" type="checkbox" /> Carry forward</label>
                        <input v-if="form.carry_forward" v-model="form.carry_forward_cap" type="number" min="0" step="0.5" class="input mt-2" placeholder="Cap (empty = company setting)" />
                    </div>
                    <div>
                        <label class="label">Max consecutive days</label>
                        <input v-model="form.max_consecutive" type="number" min="1" class="input" />
                    </div>
                    <div>
                        <label class="label">Gender restriction</label>
                        <select v-model="form.gender" class="input"><option :value="null">Any</option><option value="female">Female</option><option value="male">Male</option></select>
                    </div>
                    <div>
                        <label class="label">Colour</label>
                        <input v-model="form.color" type="color" class="input h-10 p-1" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
                    <label class="flex items-center gap-2"><input v-model="form.has_balance" type="checkbox" /> Tracks balance</label>
                    <label class="flex items-center gap-2"><input v-model="form.requires_document" type="checkbox" /> Requires document</label>
                    <label class="flex items-center gap-2"><input v-model="form.allow_half_day" type="checkbox" /> Half days</label>
                    <label class="flex items-center gap-2"><input v-model="form.allow_hours" type="checkbox" /> Hourly</label>
                    <label class="flex items-center gap-2"><input v-model="form.active" type="checkbox" /> Active</label>
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal.open = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
            </template>
        </Modal>
    </div>
</template>
