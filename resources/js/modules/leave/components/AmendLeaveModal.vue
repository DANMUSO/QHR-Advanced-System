<script setup>
/**
 * Change a request's dates (and times for hourly leave) with a live day count, then submit:
 *  mode "approve"  → supervisor Amend & Approve (POST /leave/requests/{id}/amend-approve)
 *  mode "handover" → handover colleague accepts for amended dates (POST /leave/requests/{id}/handover-decide)
 */
import { ref, watch, computed } from 'vue';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { isCompleteDate } from '@/lib/format';

const props = defineProps({ open: Boolean, request: Object, mode: { type: String, default: 'approve' } });
const emit = defineEmits(['close', 'done']);
const ui = useUiStore();

const form = ref({});
const preview = ref(null);
const previewError = ref('');
const previewing = ref(false);
const errors = ref({});
const saving = ref(false);
const hourly = computed(() => !!props.request?.start_time);

watch(() => props.open, (v) => {
    if (!v || !props.request) return;
    const r = props.request;
    form.value = { start_date: r.start_date, end_date: r.end_date, half_day: !!r.half_day, start_time: r.start_time || '', end_time: r.end_time || '', note: '' };
    errors.value = {};
    refresh();
});

let timer;
watch(() => [form.value.start_date, form.value.end_date, form.value.half_day, form.value.start_time, form.value.end_time], () => {
    clearTimeout(timer);
    timer = setTimeout(refresh, 250);
});

async function refresh() {
    if (!props.open || !isCompleteDate(form.value.start_date) || !isCompleteDate(form.value.end_date)) return;
    previewing.value = true; previewError.value = '';
    try {
        const { data } = await api.post(`/leave/requests/${props.request.id}/preview`, payload());
        preview.value = data;
    } catch (e) {
        preview.value = null;
        previewError.value = errorMessage(e);
    } finally { previewing.value = false; }
}

function payload() {
    const p = { start_date: form.value.start_date, end_date: hourly.value ? form.value.start_date : form.value.end_date, half_day: form.value.half_day };
    if (hourly.value) { p.start_time = form.value.start_time; p.end_time = form.value.end_time; }
    return p;
}

async function submit() {
    saving.value = true; errors.value = {};
    try {
        if (props.mode === 'approve') {
            await api.post(`/leave/requests/${props.request.id}/amend-approve`, { ...payload(), note: form.value.note || null });
            ui.toast('Leave amended and approved.');
        } else {
            await api.post(`/leave/requests/${props.request.id}/handover-decide`, { ...payload(), approve: true, reason: form.value.note || null });
            ui.toast('Handover accepted for the amended dates.');
        }
        emit('done');
        emit('close');
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally { saving.value = false; }
}
</script>

<template>
    <Modal :open="open" :title="mode === 'approve' ? 'Amend & Approve' : 'Amend Dates & Accept Handover'" @close="emit('close')">
        <div v-if="request" class="space-y-4">
            <p class="text-sm text-slate-600 dark:text-slate-400">
                {{ request.employee?.name }} — {{ request.leave_type?.name }}<br />
                <span class="text-xs text-slate-400">Requested: {{ request.start_date }} – {{ request.end_date }} ({{ request.days }} day(s))</span>
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label">{{ hourly ? 'Date' : 'Start date' }}</label>
                    <input v-model="form.start_date" type="date" class="input" />
                    <p v-if="errors.start_date" class="field-error">{{ errors.start_date }}</p>
                </div>
                <div v-if="!hourly">
                    <label class="label">End date</label>
                    <input v-model="form.end_date" type="date" class="input" />
                    <p v-if="errors.end_date" class="field-error">{{ errors.end_date }}</p>
                </div>
            </div>
            <div v-if="hourly" class="grid grid-cols-2 gap-4">
                <div><label class="label">From</label><input v-model="form.start_time" type="time" class="input" /></div>
                <div><label class="label">To</label><input v-model="form.end_time" type="time" class="input" /></div>
            </div>
            <label v-else-if="form.start_date && form.start_date === form.end_date" class="flex items-center gap-2 text-sm">
                <input v-model="form.half_day" type="checkbox" class="rounded" /> Half day
            </label>

            <div class="rounded-lg p-3 text-sm" :class="previewError ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300' : 'bg-brand-50 dark:bg-brand-500/10'">
                <span v-if="previewing" class="inline-flex items-center gap-2 text-slate-500"><Spinner /> Counting…</span>
                <span v-else-if="previewError">{{ previewError }}</span>
                <template v-else-if="preview">
                    <span class="font-semibold text-brand-700 dark:text-brand-300">{{ preview.days }} day(s)</span>
                    <span v-if="preview.days != request.days" class="ml-1 text-slate-500">(was {{ request.days }})</span>
                    <span v-if="preview.balance" class="ml-2 text-slate-500">· {{ preview.balance.available }} available</span>
                    <span v-if="preview.return_date" class="ml-2 text-slate-400">· returns {{ preview.return_date }}</span>
                </template>
            </div>

            <div>
                <label class="label">{{ mode === 'approve' ? 'Note to the employee (optional)' : 'Note (optional)' }}</label>
                <textarea v-model="form.note" rows="2" class="input" />
            </div>
        </div>
        <template #footer>
            <button class="btn-secondary" @click="emit('close')">Cancel</button>
            <button class="btn-primary" :disabled="saving || previewing || !!previewError || !preview" @click="submit">
                {{ saving ? 'Saving…' : (mode === 'approve' ? 'Amend & Approve' : 'Accept with new dates') }}
            </button>
        </template>
    </Modal>
</template>
