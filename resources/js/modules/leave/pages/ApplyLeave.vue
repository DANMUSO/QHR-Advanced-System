<script setup>
import { ref, watch, computed } from 'vue';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { isCompleteDate } from '@/lib/format';
import LookupSelect from '@/components/ui/LookupSelect.vue';

// request: an existing (pending) request to edit; editing sends it back through the workflow.
const props = defineProps({ open: Boolean, request: { type: Object, default: null } });
const emit = defineEmits(['close', 'done']);

const types = ref([]);
const loading = ref(false);
const submitting = ref(false);
const preview = ref(null);
const previewError = ref('');
const errors = ref({});
let previewSeq = 0; // ignore responses to superseded previews

const defaultForm = () => ({
    leave_type_id: '', start_date: '', end_date: '',
    half_day: false, start_time: '', end_time: '',
    handover1_id: '', handover2_id: '', duties: '', comment: '', document: null,
});
const form = ref(defaultForm());

const selectedType = computed(() => types.value.find(t => t.leave_type_id == form.value.leave_type_id));

let previewTimer;
watch(() => props.open, async (val) => {
    if (!val) return;
    const r = props.request;
    form.value = r ? {
        ...defaultForm(),
        leave_type_id: r.leave_type?.id ?? '', start_date: r.start_date, end_date: r.end_date, half_day: !!r.half_day,
        start_time: r.start_time ?? '', end_time: r.end_time ?? '',
        handover1_id: r.handover1?.employee?.id ?? '', handover2_id: r.handover2?.employee?.id ?? '',
        duties: r.duties ?? '', comment: r.comment ?? '',
    } : defaultForm();
    // A reopened form never shows the previous attempt's errors.
    clearTimeout(previewTimer);
    previewSeq++;
    errors.value = {};
    preview.value = null;
    previewError.value = '';
    if (r) fetchPreview();
    if (types.value.length) return;
    loading.value = true;
    try {
        const { data } = await api.get('/leave/my-balances');
        types.value = data.data;
    } finally {
        loading.value = false;
    }
});

async function fetchPreview() {
    const seq = ++previewSeq;
    previewError.value = '';
    if (!form.value.leave_type_id || !isCompleteDate(form.value.start_date) || !isCompleteDate(form.value.end_date)) {
        preview.value = null; return;
    }
    try {
        const { data } = await api.post('/leave/preview', {
            leave_type_id: form.value.leave_type_id,
            start_date: form.value.start_date,
            end_date: form.value.end_date,
            half_day: form.value.half_day,
            start_time: form.value.start_time || undefined,
            end_time: form.value.end_time || undefined,
            leave_request_id: props.request?.id,
        });
        if (seq !== previewSeq) return;
        preview.value = data;
    } catch (e) {
        if (seq !== previewSeq) return;
        preview.value = null;
        previewError.value = errorMessage(e);
    }
}

// Typing a year in a date input passes through 0002, 0020, 0202… — wait until the date is complete and settled.
watch([() => form.value.leave_type_id, () => form.value.start_date, () => form.value.end_date, () => form.value.half_day, () => form.value.start_time, () => form.value.end_time], () => {
    // Type, dates and times are validated together: any change clears their errors until the next check.
    const e = { ...errors.value };
    ['leave_type_id', 'start_date', 'end_date', 'half_day', 'start_time', 'end_time'].forEach(k => delete e[k]);
    errors.value = e;
    previewError.value = '';
    clearTimeout(previewTimer);
    previewTimer = setTimeout(fetchPreview, 300);
});

// Other fields: clear their own error once edited.
['document', 'handover1_id', 'handover2_id', 'duties', 'comment'].forEach(k => watch(() => form.value[k], () => {
    if (errors.value[k]) { const e = { ...errors.value }; delete e[k]; errors.value = e; }
}));

async function submit() {
    submitting.value = true;
    errors.value = {};
    try {
        const fd = new FormData();
        Object.entries(form.value).forEach(([k, v]) => {
            if (v !== null && v !== '' && v !== undefined) fd.append(k, v);
        });
        if (props.request) {
            fd.append('_method', 'PUT'); // multipart bodies are only parsed on POST
            await api.post(`/leave/requests/${props.request.id}`, fd, { headers: { 'Content-Type': 'multipart/form-data' } });
            swal.toast('Leave request updated and sent for approval again.');
        } else {
            await api.post('/leave/requests', fd, { headers: { 'Content-Type': 'multipart/form-data' } });
            swal.toast('Leave request submitted.');
        }
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
    <Modal :open="open" :title="request ? 'Edit Leave Request' : 'Apply for Leave'" size="lg" @close="emit('close')">
        <div v-if="loading" class="flex justify-center py-10"><Spinner /></div>
        <div v-else class="space-y-5">
            <div>
                <label class="label">Leave Type <span class="text-red-500">*</span></label>
                <select v-model="form.leave_type_id" class="input" :class="errors.leave_type_id && 'border-red-500'">
                    <option value="">— Select type —</option>
                    <option v-for="t in types" :key="t.leave_type_id" :value="t.leave_type_id">
                        {{ t.name }} ({{ t.projected ?? '∞' }} days available)
                    </option>
                </select>
                <p v-if="errors.leave_type_id" class="mt-1 text-xs text-red-500">{{ errors.leave_type_id }}</p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="label">Start Date <span class="text-red-500">*</span></label>
                    <input v-model="form.start_date" type="date" class="input" :class="errors.start_date && 'border-red-500'" />
                    <p v-if="errors.start_date" class="mt-1 text-xs text-red-500">{{ errors.start_date }}</p>
                </div>
                <div>
                    <label class="label">End Date <span class="text-red-500">*</span></label>
                    <input v-model="form.end_date" type="date" class="input" :class="errors.end_date && 'border-red-500'" />
                    <p v-if="errors.end_date" class="mt-1 text-xs text-red-500">{{ errors.end_date }}</p>
                </div>
            </div>

            <template v-if="selectedType?.allow_hours">
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="label">From Time</label><input v-model="form.start_time" type="time" class="input" /></div>
                    <div><label class="label">To Time</label><input v-model="form.end_time" type="time" class="input" /></div>
                </div>
            </template>

            <div v-if="selectedType?.allow_half_day" class="flex items-center gap-2">
                <input v-model="form.half_day" type="checkbox" id="half_day" class="rounded" />
                <label for="half_day" class="text-sm">Half day</label>
            </div>

            <div v-if="preview" class="rounded-lg bg-brand-50 p-3 text-sm dark:bg-brand-500/10">
                <span class="font-medium text-brand-700 dark:text-brand-300">{{ preview.days }} working day(s)</span>
                <span v-if="preview.balance" class="ml-2 text-slate-500">· {{ preview.balance.after }} remaining after</span>
                <span v-if="preview.return_date" class="ml-2 text-slate-400">· Return: {{ preview.return_date }}</span>
            </div>
            <div v-else-if="previewError" class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ previewError }}</div>

            <div>
                <label class="label">Handover Colleague 1 (optional)</label>
                <LookupSelect v-model="form.handover1_id" kind="employees" placeholder="Search colleague…" />
            </div>
            <div v-if="form.handover1_id">
                <label class="label">Handover Colleague 2 (optional)</label>
                <LookupSelect v-model="form.handover2_id" kind="employees" placeholder="Search colleague…" />
            </div>
            <div v-if="form.handover1_id">
                <label class="label">Duties to Hand Over</label>
                <textarea v-model="form.duties" rows="3" class="input" />
            </div>

            <div>
                <label class="label">Comment</label>
                <textarea v-model="form.comment" rows="2" class="input" />
            </div>

            <div v-if="selectedType?.requires_document">
                <label class="label">Supporting Document <span class="text-red-500">*</span></label>
                <input type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="input" @change="form.document = $event.target.files[0]" />
                <p v-if="errors.document" class="mt-1 text-xs text-red-500">{{ errors.document }}</p>
            </div>
        </div>

        <template #footer>
            <button class="btn-secondary" @click="emit('close')">Cancel</button>
            <button class="btn-primary" :disabled="submitting || loading" @click="submit">{{ submitting ? 'Submitting…' : (request ? 'Save & Resubmit' : 'Submit Request') }}</button>
        </template>
    </Modal>
</template>
