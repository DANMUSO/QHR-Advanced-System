<script setup>
import { ref, computed, onMounted } from 'vue';
import { Camera, Plus, Trash2, Clock, ShieldCheck } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import Avatar from '@/components/ui/Avatar.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Spinner from '@/components/ui/Spinner.vue';

/**
 * My profile. Personal fields save directly; statutory numbers, next of kin and pay details
 * are sent to HR as a change request and applied once approved.
 */
const profile = ref(null);
const loading = ref(false);
const saving = ref(false);
const errors = ref({});
const tab = ref('personal');
const tabs = [
    { key: 'personal', label: 'Personal' },
    { key: 'statutory', label: 'Statutory' },
    { key: 'family', label: 'Next of Kin' },
    { key: 'pay', label: 'Pay Details' },
];
const statutoryFields = [['national_id', 'National ID / Passport'], ['kra_pin', 'KRA PIN'], ['nssf_number', 'NSSF No.'], ['shif_number', 'SHIF / NHIF No.'], ['helb_number', 'HELB No.']];
const relationships = ['spouse', 'mother', 'father', 'brother', 'sister', 'son', 'daughter', 'guardian', 'relative', 'friend'];
const labels = { national_id: 'National ID', kra_pin: 'KRA PIN', nssf_number: 'NSSF No.', shif_number: 'SHIF No.', helb_number: 'HELB No.', next_of_kin: 'Next of kin', payment: 'Pay details' };

const statutory = ref({});
const nok = ref([]);
const payment = ref({});
const note = ref('');

const pending = computed(() => profile.value?.pending_change);
const pendingKeys = computed(() => Object.keys(pending.value?.changes ?? {}));

function hydrate(p) {
    profile.value = p;
    statutory.value = Object.fromEntries(statutoryFields.map(([k]) => [k, p[k] ?? '']));
    nok.value = (p.next_of_kin ?? []).map((r) => ({ ...r }));
    payment.value = { payment_method: p.payment?.payment_method === 'mpesa' ? 'mpesa' : 'bank', bank_name: '', bank_branch: '', bank_code: '', branch_code: '', account_name: p.full_name ?? '', account_number: '', mpesa_phone: '' };
    note.value = '';
    errors.value = {};
}

async function load() {
    loading.value = true;
    try { const { data } = await api.get('/profile'); hydrate(data.data); }
    catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { loading.value = false; }
}

async function savePersonal() {
    saving.value = true; errors.value = {};
    const p = profile.value;
    try {
        const { data } = await api.put('/profile', { phone: p.phone, postal_address: p.postal_address, residence: p.residence, marital_status: p.marital_status || null, about: p.about });
        hydrate(data.data); swal.toast(data.message);
    } catch (e) { errors.value = fieldErrors(e); swal.toast(errorMessage(e), 'error'); }
    finally { saving.value = false; }
}

async function requestChange(payload) {
    saving.value = true; errors.value = {};
    try {
        const { data } = await api.post('/profile/change-requests', { ...payload, note: note.value || null });
        swal.toast(data.message);
        await load();
    } catch (e) { errors.value = fieldErrors(e); swal.toast(errorMessage(e), 'error'); }
    finally { saving.value = false; }
}

function submitStatutory() { requestChange({ ...statutory.value }); }
function submitNok() { requestChange({ next_of_kin: nok.value }); }
function submitPayment() {
    const p = payment.value;
    requestChange({ payment: p.payment_method === 'mpesa' ? { payment_method: 'mpesa', mpesa_phone: p.mpesa_phone } : { ...p, mpesa_phone: null } });
}

async function cancelPending() {
    if (!await swal.confirm({ title: 'Cancel your pending change request?', confirmLabel: 'Yes, cancel', danger: true })) return;
    try { await api.post(`/profile/change-requests/${pending.value.id}/cancel`); swal.toast('Request cancelled.'); await load(); }
    catch (e) { swal.toast(errorMessage(e), 'error'); }
}

async function uploadPhoto(e) {
    const file = e.target.files[0]; if (!file) return;
    const fd = new FormData(); fd.append('photo', file);
    try { const { data } = await api.post('/profile/photo', fd); profile.value.photo_url = data.photo_url; swal.toast('Photo updated.'); }
    catch (err) { swal.toast(errorMessage(err), 'error'); }
}

function describe(key, value) {
    if (key === 'next_of_kin') return (value ?? []).map((r) => `${r.name} (${r.relationship})`).join(', ');
    if (key === 'payment') return value?.payment_method === 'mpesa' ? `M-Pesa ${value.mpesa_phone}` : `${value?.bank_name ?? ''} ${value?.account_number ?? ''}`;
    return value ?? '—';
}

const err = (k) => errors.value[k];
onMounted(load);
</script>

<template>
    <div v-if="loading && !profile" class="flex justify-center py-20"><Spinner /></div>
    <div v-else-if="profile">
        <div class="card mb-6 flex flex-col items-center gap-4 p-5 text-center sm:flex-row sm:p-6 sm:text-left">
            <div class="relative">
                <Avatar :src="profile.photo_url" :name="profile.full_name" size="xl" />
                <label class="absolute bottom-0 right-0 cursor-pointer rounded-full bg-brand-600 p-1.5 text-white shadow hover:bg-brand-700" title="Change photo">
                    <Camera class="size-4" />
                    <input type="file" accept="image/png,image/jpeg" class="sr-only" @change="uploadPhoto" />
                </label>
            </div>
            <div class="min-w-0 flex-1">
                <h2 class="text-xl font-semibold text-slate-900 dark:text-white">{{ profile.full_name }}</h2>
                <p class="text-slate-500 dark:text-slate-400">{{ profile.position }}<span v-if="profile.department"> · {{ profile.department }}</span></p>
                <p class="mt-1 text-xs text-slate-400">{{ profile.employee_number }}<span v-if="profile.branch"> · {{ profile.branch }}</span></p>
            </div>
            <div v-if="profile.profile_completeness != null" class="w-full sm:w-40">
                <div class="mb-1 flex justify-between text-xs text-slate-500"><span>Profile</span><span>{{ profile.profile_completeness }}%</span></div>
                <div class="h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"><div class="h-full rounded-full bg-brand-500" :style="{ width: `${profile.profile_completeness}%` }" /></div>
            </div>
        </div>

        <div v-if="pending" class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 gap-2">
                    <Clock class="mt-0.5 size-4 shrink-0" />
                    <div class="min-w-0">
                        <p class="font-medium">Waiting for HR approval</p>
                        <ul class="mt-1 space-y-0.5">
                            <li v-for="k in pendingKeys" :key="k" class="break-words"><span class="font-medium">{{ labels[k] ?? k }}:</span> {{ describe(k, pending.changes[k]) }}</li>
                        </ul>
                    </div>
                </div>
                <button class="btn-ghost !py-1 text-xs" @click="cancelPending">Cancel request</button>
            </div>
        </div>

        <Tabs v-model="tab" :tabs="tabs" />

        <!-- Personal: saves immediately -->
        <form novalidate v-if="tab === 'personal'" class="card p-4 sm:p-6" @submit.prevent="savePersonal">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div><label class="label">Phone</label><input v-model="profile.phone" type="tel" class="input" /><p v-if="err('phone')" class="field-error">{{ err('phone') }}</p></div>
                <div><label class="label">Postal Address</label><input v-model="profile.postal_address" type="text" class="input" /></div>
                <div><label class="label">Residence</label><input v-model="profile.residence" type="text" class="input" /></div>
                <div>
                    <label class="label">Marital Status</label>
                    <select v-model="profile.marital_status" class="input capitalize"><option value="">— Select —</option><option v-for="s in ['single', 'married', 'divorced', 'widowed']" :key="s" :value="s">{{ s.charAt(0).toUpperCase() + s.slice(1) }}</option></select>
                    <p v-if="err('marital_status')" class="field-error">{{ err('marital_status') }}</p>
                </div>
                <div class="sm:col-span-2"><label class="label">About Me</label><textarea v-model="profile.about" rows="3" class="input" /></div>
            </div>
            <div class="mt-6 flex justify-end"><button type="submit" class="btn-primary w-full sm:w-auto" :disabled="saving">{{ saving ? 'Saving…' : 'Save Changes' }}</button></div>
        </form>

        <!-- Sensitive tabs: go to HR for approval -->
        <div v-else class="card p-4 sm:p-6">
            <p class="mb-4 flex items-start gap-2 rounded-lg bg-slate-50 p-3 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                <ShieldCheck class="mt-0.5 size-4 shrink-0 text-brand-600" />
                Changes here are sent to HR for approval and only take effect once approved. You will be notified of the decision.
            </p>

            <div v-if="tab === 'statutory'" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div v-for="[key, label] in statutoryFields" :key="key">
                    <label class="label">{{ label }}</label>
                    <input v-model="statutory[key]" type="text" class="input uppercase" />
                    <p v-if="err(key)" class="field-error">{{ err(key) }}</p>
                </div>
            </div>

            <div v-else-if="tab === 'family'" class="space-y-3">
                <div v-for="(row, i) in nok" :key="row.id ?? `new-${i}`" class="rounded-lg border border-slate-200 p-3 sm:p-4 dark:border-slate-800">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div><label class="label">Name</label><input v-model="row.name" class="input" /><p v-if="err(`next_of_kin.${i}.name`)" class="field-error">{{ err(`next_of_kin.${i}.name`) }}</p></div>
                        <div>
                            <label class="label">Relationship</label>
                            <select v-model="row.relationship" class="input capitalize"><option value="">— Select —</option><option v-for="r in relationships" :key="r" :value="r">{{ r }}</option></select>
                            <p v-if="err(`next_of_kin.${i}.relationship`)" class="field-error">{{ err(`next_of_kin.${i}.relationship`) }}</p>
                        </div>
                        <div><label class="label">Phone</label><input v-model="row.phone" type="tel" class="input" /><p v-if="err(`next_of_kin.${i}.phone`)" class="field-error">{{ err(`next_of_kin.${i}.phone`) }}</p></div>
                        <div><label class="label">Email</label><input v-model="row.email" type="email" class="input" /></div>
                        <div class="sm:col-span-2"><label class="label">Address</label><input v-model="row.address" class="input" /></div>
                    </div>
                    <div class="mt-2 flex justify-end"><button type="button" class="btn-ghost !py-1 text-xs text-red-600" @click="nok.splice(i, 1)"><Trash2 class="size-3.5" /> Remove</button></div>
                </div>
                <p v-if="err('next_of_kin')" class="field-error">{{ err('next_of_kin') }}</p>
                <button type="button" class="btn-secondary" :disabled="nok.length >= 10" @click="nok.push({ id: null, name: '', relationship: '', phone: '', email: '', address: '' })"><Plus class="size-4" /> Add next of kin</button>
            </div>

            <div v-else-if="tab === 'pay'">
                <div class="mb-4 rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-800">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">On file</p>
                    <p v-if="!profile.payment" class="mt-1 text-slate-400">No pay details on file yet.</p>
                    <p v-else-if="profile.payment.payment_method === 'mpesa'" class="mt-1">M-Pesa · {{ profile.payment.mpesa_phone }}</p>
                    <p v-else-if="profile.payment.payment_method === 'bank'" class="mt-1">{{ profile.payment.bank_name }}<span v-if="profile.payment.bank_branch"> ({{ profile.payment.bank_branch }})</span> · {{ profile.payment.account_number }}</p>
                    <p v-else class="mt-1 capitalize">{{ profile.payment.payment_method }}</p>
                </div>
                <p class="mb-3 text-sm font-medium text-slate-700 dark:text-slate-200">New pay details</p>
                <div class="mb-4 flex gap-4 text-sm">
                    <label class="flex items-center gap-2"><input v-model="payment.payment_method" type="radio" value="bank" /> Bank</label>
                    <label class="flex items-center gap-2"><input v-model="payment.payment_method" type="radio" value="mpesa" /> M-Pesa</label>
                </div>
                <div v-if="payment.payment_method === 'bank'" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div><label class="label">Bank name</label><input v-model="payment.bank_name" class="input" /><p v-if="err('payment.bank_name')" class="field-error">{{ err('payment.bank_name') }}</p></div>
                    <div><label class="label">Branch</label><input v-model="payment.bank_branch" class="input" /></div>
                    <div><label class="label">Bank code</label><input v-model="payment.bank_code" class="input" /></div>
                    <div><label class="label">Branch code</label><input v-model="payment.branch_code" class="input" /></div>
                    <div><label class="label">Account name</label><input v-model="payment.account_name" class="input" /></div>
                    <div><label class="label">Account number</label><input v-model="payment.account_number" class="input" autocomplete="off" /><p v-if="err('payment.account_number')" class="field-error">{{ err('payment.account_number') }}</p></div>
                </div>
                <div v-else class="max-w-sm">
                    <label class="label">M-Pesa number</label><input v-model="payment.mpesa_phone" type="tel" class="input" placeholder="0712345678" />
                    <p v-if="err('payment.mpesa_phone')" class="field-error">{{ err('payment.mpesa_phone') }}</p>
                </div>
            </div>

            <div class="mt-5"><label class="label">Note to HR (optional)</label><input v-model="note" class="input" placeholder="e.g. new KRA PIN certificate uploaded under Documents" /></div>
            <div class="mt-6 flex justify-end">
                <button class="btn-primary w-full sm:w-auto" :disabled="saving" @click="tab === 'statutory' ? submitStatutory() : tab === 'family' ? submitNok() : submitPayment()">
                    {{ saving ? 'Sending…' : 'Send to HR for approval' }}
                </button>
            </div>
        </div>
    </div>
</template>
