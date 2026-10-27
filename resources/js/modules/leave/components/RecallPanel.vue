<script setup>
/** Recall staff from approved leave (in progress or upcoming) and the recall history. */
import { useFormErrors } from '@/lib/formErrors';
import { ref, onMounted } from 'vue';
import { Plus, Undo2 } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';
import LookupSelect from '@/components/ui/LookupSelect.vue';

const ui = useUiStore();
const history = ref([]);
const active = ref([]);
const loading = ref(true);
const modal = ref(false);
const form = ref({});
const target = ref(null);
const errors = useFormErrors(() => form.value);
const saving = ref(false);

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/leave/recalls');
        history.value = data.data ?? [];
        active.value = data.active ?? [];
    } finally { loading.value = false; }
}

/** Open the recall form, for a specific request or by employee. */
function open(request = null) {
    target.value = request;
    form.value = request ? { leave_request_id: request.id, recall_date: '', reason: '' } : { employee_id: '', recall_date: '', reason: '' };
    errors.value = {};
    modal.value = true;
}

async function save() {
    saving.value = true; errors.value = {};
    try {
        const { data } = await api.post('/leave/recalls', form.value);
        ui.toast(data.message ?? 'Employee recalled from leave.');
        modal.value = false;
        await load();
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally { saving.value = false; }
}

defineExpose({ open });
onMounted(load);
</script>

<template>
    <div>
        <div class="mb-4 flex justify-end">
            <button class="btn-primary" @click="open()"><Plus class="size-4" /> Recall by employee</button>
        </div>
        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <template v-else>
            <h3 class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-300">Approved leave that can be recalled</h3>
            <div v-if="!active.length" class="card mb-6 p-6 text-center text-sm text-slate-400">No approved leave in progress or upcoming.</div>
            <div v-else class="mb-6 space-y-2">
                <div v-for="r in active" :key="r.id" class="card flex flex-wrap items-center gap-3 p-4">
                    <Avatar :src="r.employee?.photo_url" :name="r.employee?.name" size="sm" />
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-slate-900 dark:text-white">{{ r.employee?.name }}</p>
                        <p class="text-sm text-slate-500">{{ r.leave_type?.name }} · {{ r.start_date }} – {{ r.end_date }} ({{ r.days }} days)</p>
                    </div>
                    <Badge :color="r.started ? 'green' : 'blue'">{{ r.started ? 'In progress' : 'Upcoming' }}</Badge>
                    <button class="btn-secondary !py-1.5" @click="open(r)"><Undo2 class="size-4" /> Recall</button>
                </div>
            </div>

            <h3 class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-300">Recall history</h3>
            <div v-if="!history.length" class="card p-6 text-center text-sm text-slate-400">No recalls recorded.</div>
            <div v-else class="card overflow-x-auto">
                <table class="w-full min-w-[36rem] text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                        <tr>
                            <th class="px-4 py-3 text-left">Employee</th>
                            <th class="px-4 py-3 text-left">Leave type</th>
                            <th class="px-4 py-3 text-left">Recall date</th>
                            <th class="px-4 py-3 text-right">Days credited</th>
                            <th class="px-4 py-3 text-left">Reason</th>
                            <th class="px-4 py-3 text-left">By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="r in history" :key="r.id" class="hover:bg-slate-50/70 dark:hover:bg-slate-800/30">
                            <td class="px-4 py-3 font-medium">{{ r.employee_name }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ r.leave_type_name }}</td>
                            <td class="px-4 py-3">{{ r.recall_date }}</td>
                            <td class="px-4 py-3 text-right">{{ r.recalled_days }}</td>
                            <td class="px-4 py-3 text-xs text-slate-500">{{ r.reason }}</td>
                            <td class="px-4 py-3 text-xs text-slate-400">{{ r.recalled_by }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <Modal :open="modal" title="Recall from Leave" @close="modal = false">
            <div class="space-y-4">
                <p v-if="target" class="text-sm text-slate-600 dark:text-slate-400">{{ target.employee?.name }} — {{ target.leave_type?.name }} ({{ target.start_date }} – {{ target.end_date }})</p>
                <div v-else>
                    <label class="label">Employee</label>
                    <LookupSelect v-model="form.employee_id" kind="employees" placeholder="Choose an employee" :error="!!errors.employee_id" />
                    <p v-if="errors.employee_id" class="field-error">{{ errors.employee_id }}</p>
                </div>
                <div>
                    <label class="label">Recall date (blank = today)</label>
                    <input v-model="form.recall_date" type="date" class="input" />
                    <p v-if="errors.recall_date" class="field-error">{{ errors.recall_date }}</p>
                </div>
                <div>
                    <label class="label">Reason <span class="text-red-500">*</span></label>
                    <textarea v-model="form.reason" rows="3" class="input" />
                    <p v-if="errors.reason" class="field-error">{{ errors.reason }}</p>
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="modal = false">Cancel</button>
                <button class="btn-primary" :disabled="saving || !form.reason?.trim()" @click="save">{{ saving ? 'Recalling…' : 'Recall' }}</button>
            </template>
        </Modal>
    </div>
</template>
