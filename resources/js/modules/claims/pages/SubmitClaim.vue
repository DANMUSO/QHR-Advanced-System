<script setup>
import { ref, computed, watch } from 'vue';
import { Plus, Trash2 } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';

const props = defineProps({ open: Boolean });
const emit = defineEmits(['close', 'done']);

const meta = ref(null);
const loading = ref(false);
const submitting = ref(false);
const errors = ref({});

const defaultForm = () => ({
    claimant_type: 'self', dependant_id: '', other_name: '', other_relationship: '',
    claim_date: new Date().toLocaleDateString('en-CA'),
    provider_name: '', notes: '',
    items: [{ category: '', description: '', amount: '' }],
    receipts: [],
});
const form = ref(defaultForm());

const total = computed(() => form.value.items.reduce((s, i) => s + (parseFloat(i.amount) || 0), 0));

// Server errors for item rows (items.0.amount …), shown together under the list.
const itemErrors = computed(() => Object.fromEntries(Object.entries(errors.value).filter(([k]) => /^items\.\d+\./.test(k))));

function addItem() { form.value.items.push({ category: '', description: '', amount: '' }); }
function removeItem(i) { if (form.value.items.length > 1) form.value.items.splice(i, 1); }

// Available = limit − approved − pending, for the claim date's month and year (what the server enforces).
const balance = ref(null);
const money = (v) => v != null ? Number(v).toLocaleString('en-KE', { maximumFractionDigits: 2 }) : '—';

async function loadBalance() {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(form.value.claim_date || '')) return;
    try {
        const { data } = await api.get('/claims/summary', { params: { date: form.value.claim_date } });
        balance.value = data.balance;
    } catch { /* keep the last figures */ }
}

watch(() => props.open, async (val) => {
    if (!val) return;
    form.value = defaultForm();
    errors.value = {};
    // Always refetch: claims submitted since the last open reserve part of the balance.
    loading.value = !meta.value;
    try {
        const { data } = await api.get('/claims/summary', { params: { date: form.value.claim_date } });
        meta.value = data;
        balance.value = data.balance;
    } finally {
        loading.value = false;
    }
});

watch(() => form.value.claim_date, (d, old) => {
    if (props.open && d && old && d.slice(0, 7) !== old.slice(0, 7)) loadBalance();
});

async function submit() {
    submitting.value = true;
    errors.value = {};
    try {
        const fd = new FormData();
        Object.entries(form.value).forEach(([k, v]) => {
            if (k === 'items') {
                v.forEach((item, i) => Object.entries(item).forEach(([ik, iv]) => fd.append(`items[${i}][${ik}]`, iv)));
            } else if (k === 'receipts') {
                v.forEach(f => fd.append('receipts[]', f));
            } else if (v !== '' && v !== null) {
                fd.append(k, v);
            }
        });
        await api.post('/claims', fd, { headers: { 'Content-Type': 'multipart/form-data' } });
        swal.toast('Claim submitted for approval.');
        emit('done');
        emit('close');
    } catch (e) {
        errors.value = fieldErrors(e);
        swal.toast(errorMessage(e), 'error');
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Modal :open="open" title="Submit Medical Claim" size="lg" @close="emit('close')">
        <div v-if="loading" class="flex justify-center py-10"><Spinner /></div>
        <div v-else class="space-y-5">
            <div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800">
                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    <span>Available this year ({{ balance?.year }}): <strong>KES {{ money(balance?.annual_available) }}</strong></span>
                    <span>Available in {{ balance?.month_label }}: <strong>KES {{ money(balance?.monthly_available) }}</strong></span>
                </div>
                <p v-if="balance && (balance.annual_pending > 0 || balance.monthly_pending > 0)" class="mt-1 text-xs text-slate-500">
                    Limits KES {{ money(balance.annual_limit) }} a year and KES {{ money(balance.monthly_limit) }} a month, less approved claims
                    and KES {{ money(balance.annual_pending) }} pending approval.
                </p>
                <p v-if="balance && total > Math.min(balance.annual_available, balance.monthly_available)" class="mt-1 text-xs font-medium text-red-600 dark:text-red-400">
                    This claim is more than your available balance.
                </p>
            </div>

            <div>
                <label class="label">Claimant</label>
                <select v-model="form.claimant_type" class="input">
                    <option v-for="[k,v] in Object.entries(meta?.claimant_types ?? {})" :key="k" :value="k">{{ v }}</option>
                </select>
            </div>
            <div v-if="form.claimant_type === 'dependant'">
                <label class="label">Dependant <span class="text-red-500">*</span></label>
                <select v-model="form.dependant_id" class="input" :class="errors.dependant_id && 'border-red-500'">
                    <option value="">— Select —</option>
                    <option v-for="d in meta?.dependants" :key="d.id" :value="d.id">{{ d.name }} ({{ d.relationship }})</option>
                </select>
                <p v-if="errors.dependant_id" class="mt-1 text-xs text-red-500">{{ errors.dependant_id }}</p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="label">Claim Date <span class="text-red-500">*</span></label>
                    <input v-model="form.claim_date" type="date" class="input" :class="errors.claim_date && 'border-red-500'" />
                    <p v-if="errors['claim_date']" class="mt-1 text-xs text-red-500">{{ errors['claim_date'] }}</p>
                </div>
                <div>
                    <label class="label">Provider / Hospital <span class="text-red-500">*</span></label>
                    <input v-model="form.provider_name" type="text" class="input" :class="errors.provider_name && 'border-red-500'" />
                    <p v-if="errors['provider_name']" class="mt-1 text-xs text-red-500">{{ errors['provider_name'] }}</p>
                </div>
            </div>

            <div>
                <label class="label">Claim Items <span class="text-red-500">*</span></label>
                <div v-for="(item, i) in form.items" :key="i" class="mb-2 flex gap-2">
                    <select v-model="item.category" class="input flex-1" :class="errors[`items.${i}.category`] && 'border-red-500'">
                        <option value="">— Category —</option>
                        <option v-for="[k,v] in Object.entries(meta?.categories ?? {})" :key="k" :value="k">{{ v }}</option>
                    </select>
                    <input v-model="item.description" type="text" class="input flex-1" placeholder="Description" />
                    <input v-model="item.amount" type="number" min="1" step="0.01" class="input w-32" placeholder="Amount" :class="errors[`items.${i}.amount`] && 'border-red-500'" />
                    <button type="button" class="btn-ghost !p-2 text-red-500" @click="removeItem(i)"><Trash2 class="size-4" /></button>
                </div>
                <p v-for="(msg, k) in itemErrors" :key="k" class="text-xs text-red-500">{{ msg }}</p>
                <p v-if="errors.items" class="text-xs text-red-500">{{ errors.items }}</p>
                <button type="button" class="btn-secondary mt-1 text-sm" @click="addItem"><Plus class="size-4" /> Add Item</button>
                <p class="mt-2 text-right text-sm font-medium">Total: <strong>{{ total.toLocaleString() }}</strong></p>
            </div>

            <div>
                <label class="label">Receipts (PDF/JPG/PNG, max 5 MB each)</label>
                <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png" class="input" @change="form.receipts = Array.from($event.target.files)" />
                <p v-if="errors.receipts" class="mt-1 text-xs text-red-500">{{ errors.receipts }}</p>
            </div>

            <div>
                <label class="label">Notes</label>
                <textarea v-model="form.notes" rows="2" class="input" />
            </div>
        </div>

        <template #footer>
            <button class="btn-secondary" @click="emit('close')">Cancel</button>
            <button class="btn-primary" :disabled="submitting || loading" @click="submit">{{ submitting ? 'Submitting…' : 'Submit Claim' }}</button>
        </template>
    </Modal>
</template>
