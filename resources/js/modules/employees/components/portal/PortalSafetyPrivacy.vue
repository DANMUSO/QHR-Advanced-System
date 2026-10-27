<script setup>
import { ref, computed, onMounted } from 'vue';
import { ShieldAlert, Lock, Plus } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import { formatDate, titleCase } from '@/lib/format';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';

/** Health & safety reports (hazards, incidents) and data-protection requests (access, correction, erasure) to HR. */
const reports = ref([]);
const requests = ref([]);
const loading = ref(false);
const errors = ref({});
const saving = ref(false);

const now = () => {
    const d = new Date();
    return `${d.toLocaleDateString('en-CA')}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};
const today = () => new Date().toLocaleDateString('en-CA');

const CATEGORIES = {
    hazard: ['hazard', 'near_miss', 'accident', 'fire_safety', 'ergonomics'],
    incident: ['accident', 'near_miss', 'property_damage', 'security'],
};
const LEVELS = { hazard: ['low', 'medium', 'high', 'critical'], incident: ['minor', 'moderate', 'serious', 'critical'] };
const DSR_TYPES = [
    { value: 'access', label: 'Access my data', hint: 'Get a copy of the personal data HR holds about you (answered within 7 days).' },
    { value: 'rectification', label: 'Correct my data', hint: 'Fix personal data that is wrong or incomplete (within 14 days).' },
    { value: 'erasure', label: 'Erase my data', hint: 'Delete data HR no longer needs or has no legal basis to keep (within 14 days).' },
];
const statusColors = { open: 'yellow', in_progress: 'blue', investigating: 'blue', pending: 'yellow', closed: 'green', completed: 'green', rejected: 'red' };

const safetyOpen = ref(false);
const safety = ref({});
const dsrOpen = ref(false);
const dsr = ref({});
const dsrHint = computed(() => DSR_TYPES.find((t) => t.value === dsr.value.type)?.hint);

async function load() {
    loading.value = true;
    try {
        const [s, d] = await Promise.all([api.get('/my/health-safety'), api.get('/my/data-requests')]);
        reports.value = s.data.data ?? [];
        requests.value = d.data.data ?? [];
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { loading.value = false; }
}

function openSafety(kind) {
    safety.value = { kind, title: '', category: CATEGORIES[kind][0], level: LEVELS[kind][1], date: kind === 'incident' ? now() : today(), location: '', description: '', affected_me: false };
    errors.value = {}; safetyOpen.value = true;
}
async function submitSafety() {
    const f = safety.value;
    // Mirrors the server: hazards and incidents are things that already happened.
    const max = f.kind === 'incident' ? now() : today();
    if (f.date && f.date > max) { errors.value = { date: 'The date cannot be in the future.' }; return; }
    saving.value = true; errors.value = {};
    try {
        const { data } = await api.post('/my/health-safety', { ...f, date: f.kind === 'incident' && f.date ? f.date.replace('T', ' ') : f.date });
        swal.toast(data.message); safetyOpen.value = false; await load();
    } catch (e) { errors.value = fieldErrors(e); swal.toast(errorMessage(e), 'error'); }
    finally { saving.value = false; }
}

function openDsr() { dsr.value = { type: 'access', description: '' }; errors.value = {}; dsrOpen.value = true; }
async function submitDsr() {
    saving.value = true; errors.value = {};
    try { const { data } = await api.post('/my/data-requests', dsr.value); swal.toast(data.message); dsrOpen.value = false; await load(); }
    catch (e) { errors.value = fieldErrors(e); swal.toast(errorMessage(e), 'error'); }
    finally { saving.value = false; }
}
onMounted(load);
</script>

<template>
    <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
    <div v-else class="grid gap-6 lg:grid-cols-2">
        <!-- Health & safety -->
        <div class="card p-5">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 class="flex items-center gap-2 font-semibold text-slate-900 dark:text-white"><ShieldAlert class="size-4 text-amber-500" /> Health &amp; safety</h3>
                <div class="flex gap-2">
                    <button class="btn-secondary !py-1.5" @click="openSafety('hazard')"><Plus class="size-4" /> Hazard</button>
                    <button class="btn-primary !py-1.5" @click="openSafety('incident')"><Plus class="size-4" /> Incident</button>
                </div>
            </div>
            <p class="mb-3 text-xs text-slate-500">Report an unsafe condition (hazard) or something that happened (accident, near miss, damage, security).</p>
            <div class="space-y-2">
                <div v-for="r in reports" :key="`${r.kind}-${r.id}`" class="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-medium text-slate-900 dark:text-white">{{ r.title }}</p>
                        <Badge :color="statusColors[r.status] ?? 'slate'">{{ titleCase(r.status) }}</Badge>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">{{ titleCase(r.kind) }}<span v-if="r.reference"> · {{ r.reference }}</span> · {{ titleCase(r.category) }} · {{ titleCase(r.level) }} · {{ formatDate(r.date) }}</p>
                    <p v-if="r.outcome" class="mt-2 rounded-lg bg-emerald-50 p-2 text-xs text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200">Action: {{ r.outcome }}</p>
                </div>
                <p v-if="!reports.length" class="py-6 text-center text-sm text-slate-400">You have not reported anything.</p>
            </div>
        </div>

        <!-- Data protection -->
        <div class="card p-5">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 class="flex items-center gap-2 font-semibold text-slate-900 dark:text-white"><Lock class="size-4 text-indigo-500" /> My data</h3>
                <button class="btn-primary !py-1.5" @click="openDsr"><Plus class="size-4" /> New request</button>
            </div>
            <p class="mb-3 text-xs text-slate-500">Under the Data Protection Act you can ask HR for a copy of your personal data, or to correct or erase it.</p>
            <div class="space-y-2">
                <div v-for="r in requests" :key="r.id" class="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-medium text-slate-900 dark:text-white">{{ DSR_TYPES.find((t) => t.value === r.type)?.label ?? titleCase(r.type) }}</p>
                        <Badge :color="statusColors[r.status] ?? 'slate'">{{ titleCase(r.status) }}</Badge>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">{{ r.reference }} · sent {{ formatDate(r.submitted_date) }} · due {{ formatDate(r.due_date) }}</p>
                    <p v-if="r.response_notes" class="mt-2 rounded-lg bg-slate-50 p-2 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-200">HR: {{ r.response_notes }}</p>
                </div>
                <p v-if="!requests.length" class="py-6 text-center text-sm text-slate-400">No data requests yet.</p>
            </div>
        </div>

        <Modal :open="safetyOpen" :title="safety.kind === 'incident' ? 'Report an incident' : 'Report a hazard'" @close="safetyOpen = false">
            <div class="space-y-4">
                <div><label class="label">Title</label><input v-model="safety.title" class="input" maxlength="255" /><p v-if="errors.title" class="field-error">{{ errors.title }}</p></div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div><label class="label">{{ safety.kind === 'incident' ? 'Type' : 'Category' }}</label>
                        <select v-model="safety.category" class="input"><option v-for="c in CATEGORIES[safety.kind] ?? []" :key="c" :value="c">{{ titleCase(c) }}</option></select></div>
                    <div><label class="label">{{ safety.kind === 'incident' ? 'Severity' : 'Risk level' }}</label>
                        <select v-model="safety.level" class="input"><option v-for="l in LEVELS[safety.kind] ?? []" :key="l" :value="l">{{ titleCase(l) }}</option></select></div>
                    <div><label class="label">{{ safety.kind === 'incident' ? 'When it happened' : 'Date noticed' }}</label>
                        <input v-if="safety.kind === 'incident'" v-model="safety.date" type="datetime-local" class="input" :max="now()" />
                        <input v-else v-model="safety.date" type="date" class="input" :max="today()" />
                        <p v-if="errors.date" class="field-error">{{ errors.date }}</p></div>
                    <div v-if="safety.kind === 'incident'"><label class="label">Location</label><input v-model="safety.location" class="input" /></div>
                </div>
                <div><label class="label">What happened / what is unsafe</label><textarea v-model="safety.description" rows="4" class="input" /><p v-if="errors.description" class="field-error">{{ errors.description }}</p></div>
                <label v-if="safety.kind === 'incident'" class="flex items-center gap-2 text-sm"><input v-model="safety.affected_me" type="checkbox" class="rounded" /> I was affected (injured or involved)</label>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="safetyOpen = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="submitSafety">{{ saving ? 'Sending…' : 'Send to HR' }}</button>
            </template>
        </Modal>

        <Modal :open="dsrOpen" title="Data protection request" @close="dsrOpen = false">
            <div class="space-y-4">
                <div><label class="label">Request</label>
                    <select v-model="dsr.type" class="input"><option v-for="t in DSR_TYPES" :key="t.value" :value="t.value">{{ t.label }}</option></select>
                    <p class="mt-1 text-xs text-slate-500">{{ dsrHint }}</p><p v-if="errors.type" class="field-error">{{ errors.type }}</p></div>
                <div><label class="label">Details</label><textarea v-model="dsr.description" rows="4" class="input" placeholder="Which data, and what should change?" /><p v-if="errors.description" class="field-error">{{ errors.description }}</p></div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="dsrOpen = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="submitDsr">{{ saving ? 'Sending…' : 'Send request' }}</button>
            </template>
        </Modal>
    </div>
</template>
