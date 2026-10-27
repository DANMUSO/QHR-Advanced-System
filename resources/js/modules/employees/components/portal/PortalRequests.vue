<script setup>
import { ref, computed, onMounted } from 'vue';
import { FileText, LogOut, Download, Plus } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import { formatDate, titleCase } from '@/lib/format';
import { openServerFile } from '@/lib/export';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';

/** Requests to HR: letters (introduction, employment confirmation, no objection), resignation and profile-change history. */
const data = ref(null);
const loading = ref(false);
const statusColors = { pending: 'yellow', approved: 'green', issued: 'green', processed: 'green', rejected: 'red', declined: 'red', cancelled: 'slate', withdrawn: 'slate' };

const letterOpen = ref(false);
const letterForm = ref({});
const resignOpen = ref(false);
const resignForm = ref({});
const errors = ref({});
const saving = ref(false);

const pendingResignation = computed(() => data.value?.resignations?.find((r) => r.status === 'pending'));
// Earliest allowed last working day: notice date + the company's notice period (the server enforces the same rule).
const noticeDays = computed(() => data.value?.resignation_notice?.days ?? 0);
const earliestLastDay = computed(() => {
    const from = resignForm.value.notice_date;
    if (!from) return data.value?.resignation_notice?.earliest_last_day ?? '';
    const d = new Date(`${from}T00:00:00`);
    d.setDate(d.getDate() + noticeDays.value);
    return d.toLocaleDateString('en-CA');
});

async function load() {
    loading.value = true;
    try { data.value = (await api.get('/profile/requests')).data; }
    catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { loading.value = false; }
}

function openLetter() { letterForm.value = { template: '', addressed_to: '', purpose: '' }; errors.value = {}; letterOpen.value = true; }
async function submitLetter() {
    saving.value = true; errors.value = {};
    try { const res = await api.post('/profile/letter-requests', letterForm.value); swal.toast(res.data.message); letterOpen.value = false; await load(); }
    catch (e) { errors.value = fieldErrors(e); swal.toast(errorMessage(e), 'error'); }
    finally { saving.value = false; }
}

function openResign() {
    resignForm.value = { notice_date: new Date().toLocaleDateString('en-CA'), last_working_day: data.value?.resignation_notice?.earliest_last_day ?? '', reason: '', letter: null };
    errors.value = {}; resignOpen.value = true;
}
async function submitResign() {
    if (resignForm.value.last_working_day && earliestLastDay.value && resignForm.value.last_working_day < earliestLastDay.value) {
        errors.value = { last_working_day: `With a ${noticeDays.value}-day notice period, your last working day can be no earlier than ${formatDate(earliestLastDay.value)}.` };
        return;
    }
    if (!await swal.confirm({ title: 'Submit your resignation?', text: 'HR and your supervisor will be notified.', confirmLabel: 'Submit', danger: true })) return;
    saving.value = true; errors.value = {};
    try {
        const fd = new FormData();
        Object.entries(resignForm.value).forEach(([k, v]) => { if (v !== null && v !== '') fd.append(k, v); });
        const res = await api.post('/profile/resignations', fd);
        swal.toast(res.data.message); resignOpen.value = false; await load();
    } catch (e) { errors.value = fieldErrors(e); swal.toast(errorMessage(e), 'error'); }
    finally { saving.value = false; }
}

async function cancel(url, title) {
    if (!await swal.confirm({ title, confirmLabel: 'Yes', danger: true })) return;
    try { const res = await api.post(url); swal.toast(res.data.message); await load(); }
    catch (e) { swal.toast(errorMessage(e), 'error'); }
}

onMounted(load);
</script>

<template>
    <div v-if="loading && !data" class="flex justify-center py-20"><Spinner /></div>
    <div v-else-if="data" class="grid gap-6 lg:grid-cols-2">
        <!-- Letters -->
        <section class="card p-4 sm:p-5">
            <div class="mb-3 flex items-center justify-between gap-2">
                <h3 class="flex items-center gap-2 font-semibold text-slate-900 dark:text-white"><FileText class="size-4 text-brand-600" /> Letters</h3>
                <button class="btn-primary !py-1.5" @click="openLetter"><Plus class="size-4" /> Request a letter</button>
            </div>
            <div class="space-y-2">
                <div v-for="r in data.letter_requests" :key="r.id" class="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="font-medium">{{ r.template_name }}</p>
                        <Badge :color="statusColors[r.status] ?? 'slate'">{{ titleCase(r.status) }}</Badge>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">{{ formatDate(r.created_at) }}<span v-if="r.addressed_to"> · to {{ r.addressed_to }}</span></p>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ r.purpose }}</p>
                    <p v-if="r.decision_note" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ r.decision_note }}</p>
                    <div class="mt-2 flex gap-2">
                        <button v-if="r.letter" class="btn-secondary !py-1 text-xs" @click="openServerFile(r.letter.path)"><Download class="size-3.5" /> Download</button>
                        <button v-if="r.status === 'pending'" class="btn-ghost !py-1 text-xs" @click="cancel(`/profile/letter-requests/${r.id}/cancel`, 'Cancel this letter request?')">Cancel</button>
                    </div>
                </div>
                <p v-if="!data.letter_requests.length" class="py-6 text-center text-sm text-slate-400">No letter requests yet.</p>
            </div>
        </section>

        <!-- Resignation -->
        <section class="card p-4 sm:p-5">
            <div class="mb-3 flex items-center justify-between gap-2">
                <h3 class="flex items-center gap-2 font-semibold text-slate-900 dark:text-white"><LogOut class="size-4 text-red-500" /> Resignation</h3>
                <button v-if="!pendingResignation" class="btn-danger !py-1.5" @click="openResign">Resign</button>
            </div>
            <div class="space-y-2">
                <div v-for="r in data.resignations" :key="r.id" class="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="font-medium">Last working day {{ formatDate(r.last_working_day) }}</p>
                        <Badge :color="statusColors[r.status] ?? 'slate'">{{ titleCase(r.status) }}</Badge>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">Submitted {{ formatDate(r.created_at) }} · notice from {{ formatDate(r.notice_date) }}</p>
                    <p class="mt-1 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ r.reason }}</p>
                    <p v-if="r.decision_note" class="mt-1 text-xs text-red-600 dark:text-red-400">HR: {{ r.decision_note }}</p>
                    <p v-if="r.exit" class="mt-1 text-xs font-medium text-emerald-700 dark:text-emerald-400">HR has {{ r.exit.status === 'completed' ? 'processed' : 'scheduled' }} your exit for {{ formatDate(r.exit.exit_date) }}.</p>
                    <div class="mt-2 flex gap-2">
                        <button v-if="r.letter_path" class="btn-ghost !py-1 text-xs" @click="openServerFile(r.letter_path)"><Download class="size-3.5" /> {{ r.letter_name }}</button>
                        <button v-if="r.status === 'pending'" class="btn-ghost !py-1 text-xs text-red-600" @click="cancel(`/profile/resignations/${r.id}/withdraw`, 'Withdraw your resignation?')">Withdraw</button>
                    </div>
                </div>
                <p v-if="!data.resignations.length" class="py-6 text-center text-sm text-slate-400">You have not submitted a resignation.</p>
            </div>
        </section>

        <!-- Profile change history -->
        <section class="card p-4 sm:p-5 lg:col-span-2">
            <h3 class="mb-3 font-semibold text-slate-900 dark:text-white">Profile change requests</h3>
            <div class="space-y-2">
                <div v-for="r in data.profile_changes" :key="r.id" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                    <div class="min-w-0">
                        <p class="font-medium">{{ r.summary }}</p>
                        <p class="text-xs text-slate-500">{{ formatDate(r.created_at) }}<span v-if="r.decided_by"> · {{ titleCase(r.status) }} by {{ r.decided_by }}</span><span v-if="r.decision_note"> · {{ r.decision_note }}</span></p>
                    </div>
                    <Badge :color="statusColors[r.status] ?? 'slate'">{{ titleCase(r.status) }}</Badge>
                </div>
                <p v-if="!data.profile_changes.length" class="py-4 text-center text-sm text-slate-400">Changes to statutory numbers, next of kin or pay details are listed here.</p>
            </div>
        </section>

        <Modal :open="letterOpen" title="Request a letter" @close="letterOpen = false">
            <div class="space-y-4">
                <div>
                    <label class="label">Letter</label>
                    <select v-model="letterForm.template" class="input"><option value="">— Select —</option><option v-for="t in data.letter_templates" :key="t.key" :value="t.key">{{ t.name }}</option></select>
                    <p v-if="letterForm.template" class="mt-1 text-xs text-slate-500">{{ data.letter_templates.find((t) => t.key === letterForm.template)?.description }}</p>
                    <p v-if="errors.template" class="field-error">{{ errors.template }}</p>
                </div>
                <div><label class="label">Addressed to (optional)</label><input v-model="letterForm.addressed_to" class="input" placeholder="e.g. The Manager, KCB Bank" /></div>
                <div>
                    <label class="label">Purpose</label><textarea v-model="letterForm.purpose" rows="3" class="input" placeholder="What is the letter for?" />
                    <p v-if="errors.purpose" class="field-error">{{ errors.purpose }}</p>
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="letterOpen = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="submitLetter">{{ saving ? 'Sending…' : 'Send request' }}</button>
            </template>
        </Modal>

        <Modal :open="resignOpen" title="Submit resignation" @close="resignOpen = false">
            <div class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div><label class="label">Notice date</label><input v-model="resignForm.notice_date" type="date" class="input" /><p v-if="errors.notice_date" class="field-error">{{ errors.notice_date }}</p></div>
                    <div><label class="label">Last working day</label><input v-model="resignForm.last_working_day" type="date" class="input" :min="earliestLastDay" />
                        <p v-if="earliestLastDay && !errors.last_working_day" class="mt-1 text-xs text-slate-500">{{ noticeDays }}-day notice: earliest {{ formatDate(earliestLastDay) }}.</p>
                        <p v-if="errors.last_working_day" class="field-error">{{ errors.last_working_day }}</p></div>
                </div>
                <div><label class="label">Reason</label><textarea v-model="resignForm.reason" rows="4" class="input" /><p v-if="errors.reason" class="field-error">{{ errors.reason }}</p></div>
                <div><label class="label">Resignation letter (optional)</label><input type="file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="input" @change="resignForm.letter = $event.target.files[0] ?? null" /><p v-if="errors.letter" class="field-error">{{ errors.letter }}</p></div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="resignOpen = false">Cancel</button>
                <button class="btn-danger" :disabled="saving" @click="submitResign">{{ saving ? 'Submitting…' : 'Submit resignation' }}</button>
            </template>
        </Modal>
    </div>
</template>
